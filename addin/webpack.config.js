/* eslint-disable no-undef */
const path = require("path");
const HtmlWebpackPlugin = require("html-webpack-plugin");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const devCerts = require("office-addin-dev-certs");

const DEV_SERVER_PORT = 3000;

module.exports = async (env, options) => {
  const isDev = options.mode === "development";

  const httpsOptions = await devCerts.getHttpsServerOptions();

  return {
    devtool: isDev ? "source-map" : false,
    entry: {
      taskpane: ["core-js/stable", "./src/taskpane/taskpane.js"],
      commands: "./src/commands/commands.js",
    },
    output: {
      path: path.resolve(__dirname, "dist"),
      filename: "[name].[contenthash].js",
      clean: true,
    },
    resolve: {
      extensions: [".js"],
    },
    module: {
      rules: [
        {
          test: /\.js$/,
          exclude: /node_modules/,
          use: "babel-loader",
        },
        {
          test: /\.css$/,
          use: ["style-loader", "css-loader"],
        },
      ],
    },
    plugins: [
      new HtmlWebpackPlugin({
        filename: "taskpane.html",
        template: "./src/taskpane/taskpane.html",
        chunks: ["taskpane"],
      }),
      new HtmlWebpackPlugin({
        filename: "commands.html",
        template: "./src/commands/commands.html",
        chunks: ["commands"],
      }),
      new CopyWebpackPlugin({
        patterns: [{ from: "assets", to: "assets" }],
      }),
    ],
    devServer: {
      // CORS large uniquement en développement (hôtes Office locaux). Ne jamais réutiliser
      // cet en-tête pour l'hébergement de production.
      headers: isDev ? { "Access-Control-Allow-Origin": "*" } : {},
      server: {
        type: "https",
        options: httpsOptions,
      },
      port: DEV_SERVER_PORT,
      static: false,
    },
  };
};
